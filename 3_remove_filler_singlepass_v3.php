<?php
/**
 * 3_remove_filler_singlepass_v3.php
 *
 * Single-pass FFmpeg version: removes filler words (e.g., "heu", "euh", "[UH]", "[UM]") from an MP4
 * using ONE ffmpeg command with select/aselect filters (no intermediate segment files).
 *
 * Transcript format expected (word-level timestamps), e.g.:
 *   [  1.72 →   1.84] heu
 *
 * Requirements:
 * - ffmpeg + ffprobe in PATH
 * - (Optional) NVIDIA GPU for h264_nvenc
 *
 * Example:
 * php 3_remove_filler_singlepass_v3.php \
 *   --input "input.mp4" \
 *   --transcript "03_Transcript_avec_timecodes.txt" \
 *   --output "output_clean.mp4" \
 *   --word="heu,heu.,euh,[UH],[UM]" \
 *   --pad=0.20  # symmetric (before=after)
 *   # or: --pad-before=0.15 --pad-after=0.25 \
 *   --merge-gap=0.12 \
 *   --min-word-dur=0.12 \
 *   --vcodec=h264_nvenc --preset=p7 --cq=18 \
 *   --acodec=aac --ab=192k \
 *   --progress=1
 */

function fail(string $msg, int $code = 1): void {
    fwrite(STDERR, "ERROR: $msg\n");
    exit($code);
}

function ffprobe_duration(string $input): float {
    $cmd = "ffprobe -v error -show_entries format=duration -of default=nw=1:nk=1 " . escapeshellarg($input);
    $out = trim((string)shell_exec($cmd));
    if ($out === '' || !is_numeric($out)) {
        fail("Unable to read duration with ffprobe. Is ffprobe installed? Input: $input");
    }
    return (float)$out;
}

function lower_utf8(string $s): string {
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($s, 'UTF-8');
    }
    return strtolower($s);
}

/**
 * Normalize a token for matching:
 * - lowercase
 * - trim whitespace
 * - strip leading/trailing punctuation/symbols (so "[UH]" -> "uh", "heu." -> "heu")
 */
function normalize_token(string $token): string {
    $t = trim($token);
    $t = lower_utf8($t);
    $t = preg_replace('/^[\p{P}\p{S}\s]+|[\p{P}\p{S}\s]+$/u', '', $t);
    return $t ?? '';
}

function sum_intervals(array $intervals): float {
    $sum = 0.0;
    foreach ($intervals as [$s, $e]) {
        $sum += max(0.0, (float)$e - (float)$s);
    }
    return $sum;
}

function fmt_time(float $sec): string {
    $sec = max(0.0, $sec);
    $h = floor($sec / 3600); $sec -= $h * 3600;
    $m = floor($sec / 60);   $sec -= $m * 60;
    return sprintf("%02d:%02d:%05.2f", $h, $m, $sec);
}

function parse_float_opt($value, float $default): float {
    if ($value === null || $value === false) return $default;
    $s = trim((string)$value);
    if ($s === '' || !is_numeric($s)) return $default;
    return (float)$s;
}

function parse_string_opt($value, string $default): string {
    if ($value === null || $value === false) return $default;
    $s = trim((string)$value);
    return ($s === '') ? $default : $s;
}

/**
 * Parse transcript and return list of [start,end] intervals where word matches targets.
 */
function parse_intervals(string $transcriptPath, array $targetsNormalized, float $minWordDur): array {
    $fh = fopen($transcriptPath, 'rb');
    if (!$fh) fail("Cannot open transcript: $transcriptPath");

    $intervals = [];
    // Accept both "->" and "→" and flexible spacing
    $re = '/^\[\s*([0-9]+(?:\.[0-9]+)?)\s*(?:-|–|—)?\s*(?:>|→)\s*([0-9]+(?:\.[0-9]+)?)\s*\]\s*(.+?)\s*$/u';

    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') continue;

        if (!preg_match($re, $line, $m)) continue;

        $start = (float)$m[1];
        $end   = (float)$m[2];
        $wordRaw = (string)$m[3];

        if ($end <= $start) continue;
        $dur = $end - $start;
        if ($minWordDur > 0.0 && $dur < $minWordDur) continue;

        $wordNorm = normalize_token($wordRaw);
        if ($wordNorm === '') continue;

        if (in_array($wordNorm, $targetsNormalized, true)) {
            $intervals[] = [$start, $end];
        }
    }

    fclose($fh);
    return $intervals;
}

function expand_and_merge(array $intervals, float $padBefore, float $padAfter, float $mergeGap, float $duration): array {
    if (empty($intervals)) return [];

    $expanded = [];
    foreach ($intervals as [$s, $e]) {
        $s2 = max(0.0, (float)$s - $padBefore);
        $e2 = min($duration, (float)$e + $padAfter);
        if ($e2 > $s2) $expanded[] = [$s2, $e2];
    }

    usort($expanded, fn($a, $b) => $a[0] <=> $b[0]);

    $merged = [];
    [$cs, $ce] = $expanded[0];
    for ($i = 1; $i < count($expanded); $i++) {
        [$ns, $ne] = $expanded[$i];
        if ($ns <= $ce + $mergeGap) {
            $ce = max($ce, $ne);
        } else {
            $merged[] = [$cs, $ce];
            [$cs, $ce] = [$ns, $ne];
        }
    }
    $merged[] = [$cs, $ce];

    return $merged;
}

function invert_to_keep(array $remove, float $duration, float $minKeep = 0.02): array {
    if (empty($remove)) return [[0.0, $duration]];

    $keep = [];
    $cursor = 0.0;

    foreach ($remove as [$rs, $re]) {
        if ($rs > $cursor + $minKeep) {
            $keep[] = [$cursor, $rs];
        }
        $cursor = max($cursor, $re);
    }

    if ($duration > $cursor + $minKeep) {
        $keep[] = [$cursor, $duration];
    }

    return $keep;
}

/**
 * Build an ffmpeg select/aselect expression from keep intervals:
 * between(t,a,b)+between(t,c,d)+...
 */
function build_between_expr(array $keep, int $precision = 3): string {
    $parts = [];
    foreach ($keep as [$s, $e]) {
        $a = number_format((float)$s, $precision, '.', '');
        $b = number_format((float)$e, $precision, '.', '');
        // Escape commas for ffmpeg expression parsing
        $parts[] = "between(t\\,$a\\,$b)";
    }
    return implode('+', $parts);
}

/**
 * Compute how many seconds of kept material are within [0, t] of the ORIGINAL timeline.
 * This lets us show "clean minutes processed so far" even in single-pass ffmpeg.
 */
function kept_before(float $t, array $keep): float {
    $sum = 0.0;
    foreach ($keep as [$s, $e]) {
        $s = (float)$s; $e = (float)$e;
        if ($t <= $s) break;
        $sum += max(0.0, min($t, $e) - $s);
        if ($t < $e) break;
    }
    return $sum;
}

function run_ffmpeg_with_progress(string $cmd, array $keep, float $totalKeepSeconds): void {
    $descriptors = [
        0 => ['pipe', 'r'], // stdin
        1 => ['pipe', 'w'], // stdout (ffmpeg -progress)
        2 => ['pipe', 'w'], // stderr (errors)
    ];

    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) fail("Unable to start ffmpeg process.");

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], true);
    stream_set_blocking($pipes[2], true);

    $barLen = 30;
    $lastBucket = -1;

    while (!feof($pipes[1])) {
        $line = trim((string)fgets($pipes[1]));
        if ($line === '') continue;

        if (str_starts_with($line, "out_time_ms=")) {
            $ms = (int)substr($line, strlen("out_time_ms="));
            $t = max(0.0, $ms / 1_000_000.0); // microseconds -> seconds
            $cleanSoFar = kept_before($t, $keep);
            $pct = ($totalKeepSeconds > 0) ? (100.0 * $cleanSoFar / $totalKeepSeconds) : 100.0;

            // print on ~1% buckets
            $bucket = (int)floor($pct);
            if ($bucket !== $lastBucket) {
                $lastBucket = $bucket;
                $filled = (int)round($barLen * $pct / 100.0);
                $filled = max(0, min($barLen, $filled));
                $bar = str_repeat("#", $filled) . str_repeat("-", $barLen - $filled);

                fwrite(STDERR, "\r[$bar] " . sprintf("%5.1f", $pct) . "% | clean " .
                    fmt_time($cleanSoFar) . " / " . fmt_time($totalKeepSeconds) . " | src " . fmt_time($t));
            }
        }

        if ($line === "progress=end") break;
    }

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $rc = proc_close($proc);
    fwrite(STDERR, "\n");
    if ($rc !== 0) {
        if ($stderr) fwrite(STDERR, $stderr . "\n");
        fail("ffmpeg failed with exit code $rc", $rc);
    }
}

/* -------------------- CLI -------------------- */

$__script_start = microtime(true);


$options = getopt('', [
    'input:',
    'transcript:',
    'output:',
    'word:',
    'pad:',
    'pad-before:',
    'pad-after:',
    'merge-gap:',
    'min-word-dur:',
    'vcodec:',
    'preset:',
    'cq:',
    'acodec:',
    'ab:',
    'progress:',
]);

$input      = $options['input'] ?? null;
$transcript = $options['transcript'] ?? null;
$output     = $options['output'] ?? null;

if (!$input || !$transcript || !$output) {
    fail("Missing args.\nExample:\n  php remove_filler_singlepass.php --input in.mp4 --transcript t.txt --output out.mp4 --word=heu,euh,[UH],[UM] --pad=0.20  # symmetric (before=after)
 *   # or: --pad-before=0.15 --pad-after=0.25 --merge-gap=0.12 --vcodec=h264_nvenc --preset=p7 --cq=18 --progress=1");
}

if (!is_file($input)) fail("Input video not found: $input");
if (!is_file($transcript)) fail("Transcript not found: $transcript");

$wordOpt  = parse_string_opt($options['word'] ?? null, 'heu');
$padSym   = parse_float_opt($options['pad'] ?? null, 0.06); // backward-compatible symmetric pad
$padBefore = parse_float_opt($options['pad-before'] ?? null, $padSym);
$padAfter  = parse_float_opt($options['pad-after'] ?? null,  $padSym);
$mergeGap = parse_float_opt($options['merge-gap'] ?? null, 0.12);
$minWordDur = parse_float_opt($options['min-word-dur'] ?? null, 0.0);$vcodec = parse_string_opt($options['vcodec'] ?? null, 'h264_nvenc'); // or libx264
$preset = parse_string_opt($options['preset'] ?? null, 'p7');         // nvenc presets p1..p7
$cq     = parse_string_opt($options['cq'] ?? null, '18');             // nvenc CQ
$acodec = parse_string_opt($options['acodec'] ?? null, 'aac');
$ab     = parse_string_opt($options['ab'] ?? null, '192k');

$showProgress = parse_string_opt($options['progress'] ?? null, '1');
$showProgress = in_array(strtolower($showProgress), ['1', 'true', 'yes', 'y'], true);

/* -------------------- Targets -------------------- */

$rawTargets = array_values(array_filter(array_map('trim', explode(',', $wordOpt)), fn($x) => $x !== ''));

$targetsNormalized = [];
foreach ($rawTargets as $t) {
    $n = normalize_token($t);
    if ($n !== '') $targetsNormalized[] = $n;
}
$targetsNormalized = array_values(array_unique($targetsNormalized));

fwrite(STDERR, "Input: $input\n");
fwrite(STDERR, "Transcript: $transcript\n");
fwrite(STDERR, "Output: $output\n");
fwrite(STDERR, "Pad: before={$padBefore}s after={$padAfter}s | Merge-gap: {$mergeGap}s
");
fwrite(STDERR, "Targets (normalized): " . implode(',', $targetsNormalized) . "\n");
fwrite(STDERR, "Video codec: $vcodec | preset: $preset | cq: $cq\n");
fwrite(STDERR, "Audio codec: $acodec | bitrate: $ab\n");

$duration = ffprobe_duration($input);

/* -------------------- Find intervals -------------------- */

$intervals = parse_intervals($transcript, $targetsNormalized, $minWordDur);

if (empty($intervals)) {
    fwrite(STDERR, "No matches found. Re-encoding whole video with chosen codecs.\n");
    $cmd = "ffmpeg -y -i " . escapeshellarg($input)
        . " -c:v " . escapeshellarg($vcodec);

    if (str_contains($vcodec, 'nvenc')) {
        $cmd .= " -preset " . escapeshellarg($preset) . " -cq " . escapeshellarg($cq);
    } elseif ($vcodec === 'libx264') {
        $cmd .= " -crf 18 -preset veryfast";
    }

    $cmd .= " -c:a " . escapeshellarg($acodec) . " -b:a " . escapeshellarg($ab)
        . " " . escapeshellarg($output);

    passthru($cmd, $rc);
    if ($rc !== 0) fail("ffmpeg failed (exit $rc).", $rc);

    $finalDur = ffprobe_duration($output);
    fwrite(STDERR, "Final output duration: " . fmt_time($finalDur) . " ({$finalDur}s)\n");
$__elapsed = microtime(true) - $__script_start;
fwrite(STDERR, "Total execution time: " . fmt_time($__elapsed) . " (" . sprintf("%.2f", $__elapsed) . "s)\n");
    exit(0);
}

$remove = expand_and_merge($intervals, $padBefore, $padAfter, $mergeGap, $duration);
$keep   = invert_to_keep($remove, $duration, 0.02);
usort($keep, fn($a, $b) => $a[0] <=> $b[0]);

$removedSeconds = sum_intervals($remove);
$keepSeconds    = sum_intervals($keep);

fwrite(STDERR, "Duration original: " . fmt_time($duration) . " ({$duration}s)\n");
fwrite(STDERR, "Found matches: " . count($intervals) . "\n");
fwrite(STDERR, "Remove segments (merged): " . count($remove) . " | removed " . fmt_time($removedSeconds) . "\n");
fwrite(STDERR, "Keep segments: " . count($keep) . " | clean est. " . fmt_time($keepSeconds) . "\n");

/* -------------------- Build filter and run ffmpeg once -------------------- */

$expr = build_between_expr($keep, 3);

// Reset timestamps after filtering:
$filter = "[0:v]select='$expr',setpts=N/FRAME_RATE/TB[v];"
        . "[0:a]aselect='$expr',asetpts=N/SR/TB[a]";

fwrite(STDERR, "Running single-pass ffmpeg (filter_complex)...\n");

// Build command
$cmd = "ffmpeg -y -i " . escapeshellarg($input)
     . " -filter_complex " . escapeshellarg($filter)
     . " -map " . escapeshellarg("[v]") . " -map " . escapeshellarg("[a]")
     . " -c:v " . escapeshellarg($vcodec);

if (str_contains($vcodec, 'nvenc')) {
    $cmd .= " -preset " . escapeshellarg($preset) . " -cq " . escapeshellarg($cq);
} elseif ($vcodec === 'libx264') {
    $cmd .= " -crf 18 -preset veryfast";
}

$cmd .= " -c:a " . escapeshellarg($acodec) . " -b:a " . escapeshellarg($ab);

if ($showProgress) {
    $cmd .= " -progress pipe:1 -nostats -loglevel error";
}

$cmd .= " " . escapeshellarg($output);

if ($showProgress) {
    run_ffmpeg_with_progress($cmd, $keep, $keepSeconds);
} else {
    passthru($cmd, $rc);
    if ($rc !== 0) fail("ffmpeg failed (exit $rc).", $rc);
}

$finalDur = ffprobe_duration($output);
fwrite(STDERR, "Done: $output\n");
fwrite(STDERR, "Final output duration: " . fmt_time($finalDur) . " ({$finalDur}s)\n");
$__elapsed = microtime(true) - $__script_start;
fwrite(STDERR, "Total execution time: " . fmt_time($__elapsed) . " (" . sprintf("%.2f", $__elapsed) . "s)\n");