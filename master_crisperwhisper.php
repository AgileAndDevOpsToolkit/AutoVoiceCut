<?php
/**
 * Usage:
 *   php master_crisperwhisper.php "/path/to/video.mkv|mp4"
 *
 * This script must be in the same folder as:
 *   - 1_split_on_silence.php
 *   - 2_transcribe_chunks_and_merge.php
 *   - 3_remove_filler_singlepass_v3.php
 */

/* =========================
 * Params for LAST script only (remove_filler_singlepass_v3.php)
 * ========================= */
$RF_WORDS        = "heu,heu.,euh,[UH],[UM]";
$RF_PAD_BEFORE   = 0.15;
$RF_PAD_AFTER    = 0.07;
$RF_MERGE_GAP    = 0.10;
$RF_MIN_WORD_DUR = 0.09;
$RF_VCODEC       = "h264_nvenc";
$RF_PRESET       = "p7";
$RF_CQ           = 18;
$RF_ACODEC       = "aac";
$RF_AB           = "192k";
$RF_PROGRESS     = 1;

$RF_OUTPUT_CLEAN = "output_clean.mp4";
$RF_TRANSCRIPT   = "chunks/full_transcript_timestamps.txt";

/* =========================
 * Fixed params for other steps
 * ========================= */
$CONDA_ENV_NAME = "crisperWhisper";

$AUDIO_WAV      = "audio.wav";
$CHUNKS_DIR     = "./chunks";

$SPLIT_MAXLEN      = 180;
$SPLIT_SILENCE_DB  = -45;
$SPLIT_SILENCE_DUR = 0.5;
$SPLIT_FORMAT      = "wav";

$TRANSCRIBE_PY = "/path/to/CrisperWhisper/transcribe.py";
$PYTHON_BIN    = "python";

/* =========================
 * Helpers
 * ========================= */
function fail($msg, $code = 1) {
    fwrite(STDERR, "[ERROR] " . $msg . "\n");
    exit((int)$code);
}
function q($s) { return escapeshellarg((string)$s); }
function cmd(array $parts) { return implode(' ', $parts); }

/* =========================
 * Args
 * ========================= */
if ($argc !== 2) {
    fail("Usage: php " . basename(__FILE__) . " \"/path/to/video.mkv|mp4\"");
}
$inputVideo = $argv[1];
if (!is_file($inputVideo)) {
    fail("Input video not found: " . $inputVideo);
}

$workdir = __DIR__;
$splitScript        = $workdir . "/1_split_on_silence.php";
$transcribeScript   = $workdir . "/2_transcribe_chunks_and_merge.php";
$removeFillerScript = $workdir . "/3_remove_filler_singlepass_v3.php";

foreach (array($splitScript, $transcribeScript, $removeFillerScript) as $p) {
    if (!is_file($p)) fail("Missing script in same folder: " . $p);
}

// Determine MP4 path (create if needed)
$pathInfo  = pathinfo($inputVideo);
$ext       = strtolower(isset($pathInfo['extension']) ? $pathInfo['extension'] : '');
$dirname   = isset($pathInfo['dirname']) ? $pathInfo['dirname'] : '.';
$filename  = isset($pathInfo['filename']) ? $pathInfo['filename'] : 'video';
$baseNoExt = $dirname . '/' . $filename;

$mp4Video = ($ext === 'mp4') ? $inputVideo : ($baseNoExt . ".mp4");

// Build a REAL bash script file
$tmpSh = $workdir . "/.crisperwhisper_pipeline_" . getmypid() . "_" . time() . ".sh";

$lines = array();
$lines[] = "#!/usr/bin/env bash";
$lines[] = "set -euo pipefail";
$lines[] = "cd " . q($workdir);
$lines[] = 'echo "[INFO] Working dir: $(pwd)"';
$lines[] = 'echo "[INFO] Input video: ' . addslashes($inputVideo) . '"';

// Conda bootstrap (IMPORTANT: use single quotes in PHP strings when bash uses $VAR)
$lines[] = 'echo "[INFO] Activating conda env: ' . addslashes($CONDA_ENV_NAME) . '"';
$lines[] = 'CONDA_EXE="$(command -v conda || true)"';
$lines[] = 'if [[ -z "$CONDA_EXE" ]]; then';
$lines[] = '  echo "[ERROR] conda not found in PATH."';
$lines[] = '  exit 10';
$lines[] = 'fi';
$lines[] = 'CONDA_BASE="$("$CONDA_EXE" info --base)"';
$lines[] = 'source "$CONDA_BASE/etc/profile.d/conda.sh"';
$lines[] = "conda activate " . q($CONDA_ENV_NAME);

// 2) Extract mono 16k wav
$lines[] = 'echo "[INFO] Extracting audio: ' . addslashes($AUDIO_WAV) . '"';
$lines[] = cmd(array(
    "ffmpeg", "-y",
    "-i", q($inputVideo),
    "-vn", "-ac", "1", "-ar", "16000", "-sample_fmt", "s16",
    q($AUDIO_WAV)
));

// 3) Split on silence
$lines[] = 'echo "[INFO] Splitting on silence into: ' . addslashes($CHUNKS_DIR) . '"';
$lines[] = "mkdir -p " . q($CHUNKS_DIR);
$lines[] = cmd(array(
    "php", q($splitScript),
    "--in=" . q($AUDIO_WAV),
    "--outdir=" . q($CHUNKS_DIR),
    "--maxlen=" . q($SPLIT_MAXLEN),
    "--silence_db=" . q($SPLIT_SILENCE_DB),
    "--silence_dur=" . q($SPLIT_SILENCE_DUR),
    "--format=" . q($SPLIT_FORMAT),
));

// 4) Transcribe and merge
$lines[] = 'echo "[INFO] Transcribing chunks and merging transcripts"';
$lines[] = cmd(array(
    "php", q($transcribeScript),
    "--chunksdir=" . q($CHUNKS_DIR),
    "--offsets", q($CHUNKS_DIR . "/offsets.json"),
    "--transcribe", q($TRANSCRIBE_PY),
    "--python", q($PYTHON_BIN),
));

// 5) Convert to mp4 if needed
if ($ext !== 'mp4') {
    $lines[] = 'echo "[INFO] Converting to MP4 (stream copy): ' . addslashes($mp4Video) . '"';
    $lines[] = cmd(array(
        "ffmpeg", "-y",
        "-i", q($inputVideo),
        "-c", "copy",
        q($mp4Video),
    ));
} else {
    $lines[] = 'echo "[INFO] Input already MP4, skipping conversion"';
}

// 6) Remove fillers
$lines[] = 'echo "[INFO] Removing fillers => ' . addslashes($RF_OUTPUT_CLEAN) . '"';
$lines[] = cmd(array(
    "php", q($removeFillerScript),
    "--input", q($mp4Video),
    "--transcript", q($RF_TRANSCRIPT),
    "--output", q($RF_OUTPUT_CLEAN),
    "--word=" . q($RF_WORDS),
    "--pad-before=" . q($RF_PAD_BEFORE),
    "--pad-after=" . q($RF_PAD_AFTER),
    "--merge-gap=" . q($RF_MERGE_GAP),
    "--min-word-dur=" . q($RF_MIN_WORD_DUR),
    "--vcodec=" . q($RF_VCODEC),
    "--preset=" . q($RF_PRESET),
    "--cq=" . q($RF_CQ),
    "--acodec=" . q($RF_ACODEC),
    "--ab=" . q($RF_AB),
    "--progress=" . q($RF_PROGRESS),
));

$lines[] = 'echo "[DONE] Output: ' . addslashes($RF_OUTPUT_CLEAN) . '"';

$scriptContent = implode("\n", $lines) . "\n";
if (file_put_contents($tmpSh, $scriptContent) === false) {
    fail("Unable to write temp bash script: " . $tmpSh);
}
chmod($tmpSh, 0755);

echo "[INFO] Running pipeline script: " . $tmpSh . "\n";
passthru("bash " . q($tmpSh), $exitCode);

if ((int)$exitCode !== 0) {
    fail("Pipeline failed with exit code: " . $exitCode . ". Temp script kept at: " . $tmpSh, (int)$exitCode);
}

@unlink($tmpSh);
exit(0);