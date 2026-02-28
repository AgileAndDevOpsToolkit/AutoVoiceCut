<?php
/**
 * Transcribe all chunks, store per-chunk outputs, then merge into:
 * - full_transcript_text.txt (plain text)
 * - full_transcript_timestamps.txt ([absStart -> absEnd] token)
 * - full_transcript.srt (grouped subtitles)
 *
 * Usage:
 *   php transcribe_chunks_and_merge.php \
 *     --chunksdir ./chunks \
 *     --offsets ./chunks/offsets.json \
 *     --transcribe /path/to/CrisperWhisper/transcribe.py \
 *     --python python
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$args = parseArgs($argv);

$chunksDir   = rtrim($args['chunksdir'] ?? './chunks', '/');
$offsetsPath = $args['offsets'] ?? ($chunksDir . '/offsets.json');
$transcribe  = $args['transcribe'] ?? (getenv('HOME') . '/CrisperWhisper/transcribe.py');
$pythonBin   = $args['python'] ?? 'python';

if (!is_dir($chunksDir)) die("ERROR: chunksdir not found: $chunksDir\n");
if (!is_file($offsetsPath)) die("ERROR: offsets.json not found: $offsetsPath\n");
if (!is_file($transcribe)) die("ERROR: transcribe.py not found: $transcribe\n");

$offsets = json_decode(file_get_contents($offsetsPath), true);
if (!is_array($offsets)) die("ERROR: offsets.json invalid JSON\n");

// Index offsets by filename for easy lookup
$offsetByFile = [];
foreach ($offsets as $o) {
    if (!isset($o['file'], $o['start_s'])) continue;
    $offsetByFile[$o['file']] = (float)$o['start_s'];
}

// Find chunk wav files in offsets order (safer than glob sort)
$chunkFiles = [];
foreach ($offsets as $o) {
    $f = $o['file'] ?? null;
    if (!$f) continue;
    $full = $chunksDir . '/' . $f;
    if (is_file($full)) $chunkFiles[] = $f;
}
if (!$chunkFiles) die("ERROR: No chunk files found in offsets.json list.\n");

$allItems = []; // merged timestamped items with absolute times
$fullPlainText = '';

foreach ($chunkFiles as $chunkFile) {
    $chunkPath = $chunksDir . '/' . $chunkFile;
    $chunkBase = pathinfo($chunkFile, PATHINFO_FILENAME); // chunk_000
    $offsetS   = $offsetByFile[$chunkFile] ?? 0.0;

    $outStdout = $chunksDir . '/' . $chunkBase . '.transcript.stdout.txt';
    $outStderr = $chunksDir . '/' . $chunkBase . '.transcript.stderr.log';
    $outParsed = $chunksDir . '/' . $chunkBase . '.transcript.parsed.json';

    echo "==> Transcribing $chunkFile (offset {$offsetS}s)\n";

    // IMPORTANT: keep stderr separate from stdout (no 2>&1)
    // If your transcribe.py prints everything to stdout, you'll still be OK,
    // but generally warnings go to stderr.
    $cmd = escapeshellcmd($pythonBin) . ' ' . escapeshellarg($transcribe)
         . ' --f ' . escapeshellarg($chunkPath)
         . ' 1> ' . escapeshellarg($outStdout)
         . ' 2> ' . escapeshellarg($outStderr);

    $ret = 0;
    system($cmd, $ret);
    if ($ret !== 0) {
        echo "WARN: transcribe failed for $chunkFile (exit=$ret). See: $outStderr\n";
        // continue anyway: maybe partial output exists
    }

    $stdout = is_file($outStdout) ? file_get_contents($outStdout) : '';
    if (trim($stdout) === '') {
        echo "WARN: empty stdout for $chunkFile. Nothing to parse.\n";
        file_put_contents($outParsed, json_encode([], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        continue;
    }

    // Parse timestamp lines like:
    // [  0.50 →   0.78] Bonjour
    // Be tolerant: arrow can be '→' or '->', spaces vary.
    $items = parseTimestampedLines($stdout);

    // Save parsed (chunk-relative)
    file_put_contents($outParsed, json_encode($items, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

    if (!$items) {
        echo "WARN: no timestamp lines parsed for $chunkFile. Check $outStdout\n";
        continue;
    }

    // Build plain text for this chunk (words concatenated)
    $chunkPlain = joinWords($items);
    $fullPlainText .= ($fullPlainText === '' ? '' : "\n") . $chunkPlain;

    // Merge into absolute timeline
    foreach ($items as $it) {
        $absStart = $it['start'] + $offsetS;
        $absEnd   = $it['end'] + $offsetS;
        $allItems[] = [
            'abs_start' => $absStart,
            'abs_end'   => $absEnd,
            'text'      => $it['text'],
            'chunk'     => $chunkFile,
            'rel_start' => $it['start'],
            'rel_end'   => $it['end'],
        ];
    }
}

// Write merged files
$fullTextPath = $chunksDir . '/full_transcript_text.txt';
file_put_contents($fullTextPath, $fullPlainText);

// Timestamps text
$fullTsPath = $chunksDir . '/full_transcript_timestamps.txt';
$tsLines = [];
foreach ($allItems as $it) {
    $tsLines[] = sprintf(
        "[%s -> %s] %s",
        formatSec($it['abs_start']),
        formatSec($it['abs_end']),
        $it['text']
    );
}
file_put_contents($fullTsPath, implode("\n", $tsLines));

// SRT
$fullSrtPath = $chunksDir . '/full_transcript.srt';
$srt = buildSrtFromWordItems($allItems);
file_put_contents($fullSrtPath, $srt);

echo "\nDONE.\n";
echo " - $fullTextPath\n";
echo " - $fullTsPath\n";
echo " - $fullSrtPath\n";


// ---------------- helpers ----------------

function parseArgs(array $argv): array {
    $out = [];
    for ($i=1; $i<count($argv); $i++) {
        $a = $argv[$i];
        if (substr($a,0,2) === '--') {
            $eq = strpos($a,'=');
            if ($eq !== false) {
                $k = substr($a,2,$eq-2);
                $v = substr($a,$eq+1);
                $out[$k] = $v;
            } else {
                $k = substr($a,2);
                $v = ($i+1 < count($argv) && substr($argv[$i+1],0,2) !== '--') ? $argv[++$i] : true;
                $out[$k] = $v;
            }
        }
    }
    return $out;
}

function parseTimestampedLines(string $stdout): array {
    $lines = preg_split("/\R/u", $stdout);
    $items = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        // Match both:
        // [  0.50 →   0.78] Bonjour
        // [0.50 -> 0.78] Bonjour
        // Also accept unicode arrow.
        if (preg_match('/^\[\s*([0-9]+(?:\.[0-9]+)?)\s*(?:→|->)\s*([0-9]+(?:\.[0-9]+)?)\s*\]\s*(.+)$/u', $line, $m)) {
            $start = (float)$m[1];
            $end   = (float)$m[2];
            $text  = trim($m[3]);

            // Skip weird empty tokens
            if ($text === '') continue;

            $items[] = ['start' => $start, 'end' => $end, 'text' => $text];
        }
    }
    return $items;
}

function joinWords(array $items): string {
    $out = '';
    foreach ($items as $it) {
        $w = $it['text'];

        // Basic French punctuation handling
        if ($out === '') {
            $out = $w;
            continue;
        }
        // No space before punctuation
        if (preg_match('/^[\.\,\;\:\!\?\)]$/u', $w)) {
            $out .= $w;
        } else if ($w === "'" || $w === "’") {
            $out .= $w;
        } else if (preg_match("/^(?:'|’)/u", $w)) {
            // word starting with apostrophe: attach
            $out .= $w;
        } else {
            $out .= ' ' . $w;
        }
    }
    return $out;
}

function formatSec(float $s): string {
    // Keep 3 decimals
    return number_format($s, 3, '.', '');
}

function secToSrtTime(float $sec): string {
    $ms = (int)round($sec * 1000);
    $h = intdiv($ms, 3600000); $ms -= $h*3600000;
    $m = intdiv($ms, 60000);   $ms -= $m*60000;
    $s = intdiv($ms, 1000);    $ms -= $s*1000;
    return sprintf("%02d:%02d:%02d,%03d", $h, $m, $s, $ms);
}

function buildSrtFromWordItems(array $allItems): string {
    if (!$allItems) {
        return "; No timestamps parsed -> SRT not generated.\n";
    }

    // Group words into subtitle cues with simple heuristics:
    // - new cue if gap > 0.8s
    // - or cue duration > 3.5s
    // - or words > 12
    $cues = [];
    $cur = null;

    foreach ($allItems as $it) {
        $st = $it['abs_start'];
        $en = $it['abs_end'];
        $tx = $it['text'];

        if ($cur === null) {
            $cur = ['start'=>$st, 'end'=>$en, 'words'=>[$tx]];
            continue;
        }

        $gap = $st - $cur['end'];
        $dur = $en - $cur['start'];
        $wc  = count($cur['words']);

        if ($gap > 0.8 || $dur > 3.5 || $wc >= 12) {
            $cues[] = $cur;
            $cur = ['start'=>$st, 'end'=>$en, 'words'=>[$tx]];
        } else {
            $cur['end'] = max($cur['end'], $en);
            $cur['words'][] = $tx;
        }
    }
    if ($cur !== null) $cues[] = $cur;

    // Build SRT text
    $out = [];
    $idx = 1;
    foreach ($cues as $cue) {
        $text = joinWords(array_map(fn($w)=>['text'=>$w], $cue['words']));
        $out[] = (string)$idx++;
        $out[] = secToSrtTime($cue['start']) . " --> " . secToSrtTime($cue['end']);
        $out[] = $text;
        $out[] = ""; // blank line
    }
    return implode("\n", $out);
}
