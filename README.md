# AutoVoiceCut

## 📝 Description

AutoVoiceCut is an automated audio processing pipeline that removes filler words (like "heu", "euh", "[UH]", "[UM]") from video files using speech recognition and FFmpeg. The pipeline splits audio intelligently on silence detection, transcribes the chunks using CrisperWhisper, and removes unwanted words while maintaining video quality.

## ✨ Features

- 🎵 **Silence detection** - Splits audio at natural pauses (improves performance by processing smaller chunks)
- 🤖 **Automatic transcription** - Uses CrisperWhisper for accurate speech-to-text (enables efficient filler word recognition on smaller text segments)
- 🗑️ **Filler word removal** - Cleanly removes specified filler words from both audio and video
- 🎬 **Multi-format support** - Handles MKV input files and exports to MP4 format

## 📋 Prerequisites

### Required

- PHP 7.4+ (with CLI)
- FFmpeg and FFprobe
- Python 3.7+
- Conda environment with CrisperWhisper installed

### Optional

- NVIDIA GPU for hardware video encoding (h264_nvenc codec)

## 📦 Installation

```bash
# Clone the repository
git clone https://github.com/AgileAndDevOpsToolkit/AutoVoiceCut.git
cd AutoVoiceCut

# Ensure all scripts are executable
chmod +x *.php
```

### Setting up CrisperWhisper

```bash
# Create conda environment (refer to CrisperWhisper documentation)
conda create -n crisperWhisper python=3.10
conda activate crisperWhisper
# Install CrisperWhisper according to its documentation
```

## 🚀 Usage

### Quick Start

```bash
php master_crisperwhisper
.php "/path/to/input_video.mp4" "/path/to/CrisperWhisper/transcribe.py"
```

### Detailed Usage

The pipeline consists of 3 main scripts orchestrated by the master script. This modular design allows you to edit and test individual components independently, and selectively re-run specific scripts during development without reprocessing the entire pipeline.

#### 1. Split on Silence (`1_split_on_silence.php`)

Splits audio/video into chunks at detected silence points.

This script processes audio files by detecting and splitting on silent segments.
The rationale for this preprocessing step is performance optimization: the
downstream transcription script performs significantly better and faster when
processing smaller audio chunks rather than entire files at once.

By breaking large audio files into smaller, silence-separated segments, we
reduce individual transcription job complexity and enable more efficient processing.

```bash
php 1_split_on_silence.php \
  --in "/path/to/audio.wav" \
  --outdir "./chunks" \
  --maxlen 180 \
  --silence_db -45 \
  --silence_dur 0.5 \
  --format wav
```

**Parameters:**
- `--in` - Input audio/video file
- `--outdir` - Output directory for chunks
- `--maxlen` - Maximum chunk length in seconds (default: 180)
- `--silence_db` - Silence threshold in dB (default: -45)
- `--silence_dur` - Minimum silence duration in seconds (default: 0.5)
- `--format` - Output format (default: wav)
- `--prefer_window` - Window (seconds) around target cut to look for silence (default: 12)
- `--min_chunk` - Minimum chunk duration in seconds (default: 20)

#### 2. Transcribe & Merge (`2_transcribe_chunks_and_merge.php`)

Transcribes all chunks and merges results with timestamps.

```bash
php 2_transcribe_chunks_and_merge.php \
  --chunksdir "./chunks" \
  --offsets "./chunks/offsets.json" \
  --transcribe "/path/to/CrisperWhisper/transcribe.py" \
  --python "python"
```

**Parameters:**
- `--chunksdir` - Directory containing chunks
- `--offsets` - Path to offsets.json file
- `--transcribe` - Path to CrisperWhisper transcribe.py script
- `--python` - Python binary path (default: python)

**Output files:**
- `full_transcript_text.txt` - Plain text transcript
- `full_transcript_timestamps.txt` - Word-level timestamps
- `full_transcript.srt` - SRT subtitle format

#### 3. Remove Filler Words (`3_remove_filler_singlepass_v3.php`)

Removes specified filler words from the video using single-pass FFmpeg.

```bash
php 3_remove_filler_singlepass_v3.php \
  --input "input.mp4" \
  --transcript "chunks/full_transcript_timestamps.txt" \
  --output "output_clean.mp4" \
  --word="heu,heu.,euh,[UH],[UM]" \
  --pad-before=0.15 \
  --pad-after=0.07 \
  --merge-gap=0.10 \
  --min-word-dur=0.09 \
  --vcodec="h264_nvenc" \
  --preset="p7" \
  --cq=18 \
  --acodec="aac" \
  --ab="192k" \
  --progress=1
```

**Parameters:**
- `--input` - Input video file
- `--transcript` - Transcript file with word-level timestamps
- `--output` - Output video file
- `--word` - Comma-separated filler words to remove
- `--pad-before` - Silence padding before removed word (seconds)
- `--pad-after` - Silence padding after removed word (seconds)
- `--merge-gap` - Gap threshold to merge adjacent silences (seconds)
- `--min-word-dur` - Minimum word duration to process (seconds)
- `--vcodec` - Video codec (default: h264_nvenc, or libx264)
- `--preset` - Video codec preset
- `--cq` - Quality level for h264_nvenc
- `--acodec` - Audio codec (default: aac)
- `--ab` - Audio bitrate (default: 192k)
- `--progress` - Show ffmpeg progress (0 or 1)

### Configuration

Edit the master script to customize default parameters:

```php
$RF_WORDS        = "heu,heu.,euh,[UH],[UM]";  // Filler words
$RF_PAD_BEFORE   = 0.15;                       // Padding before word
$RF_PAD_AFTER    = 0.07;                       // Padding after word
$RF_MERGE_GAP    = 0.10;                       // Gap merge threshold
$RF_MIN_WORD_DUR = 0.09;                       // Minimum word duration
$RF_VCODEC       = "h264_nvenc";               // Video codec
$RF_PRESET       = "p7";                       // FFmpeg preset
$RF_CQ           = 18;                         // Quality
$SPLIT_MAXLEN    = 180;                        // Max chunk length
$SPLIT_SILENCE_DB = -45;                        // Silence threshold
```

## 📤 Output

The processed video will be saved as `output_clean.mp4` in the working directory. Intermediate files are stored in the `chunks/` directory.

## 🔄 Pipeline Workflow

```
Input Video
    ↓
[1] Split on Silence → chunks/*.wav + offsets.json
    ↓
[2] Transcribe & Merge → full_transcript_timestamps.txt
    ↓
[3] Remove Filler Words → output_clean.mp4
```

## 🔧 Troubleshooting

### "conda not found in PATH"

Ensure conda is properly initialized. Run:
```bash
conda init bash
source ~/.bashrc
```

### "transcribe.py not found"

Provide the correct path to the CrisperWhisper transcribe.py script:
```bash
php master_crisperwhisper.php "/path/to/video.mp4" "/full/path/to/CrisperWhisper/transcribe.py"
```

### FFmpeg codec not available

If h264_nvenc is not available, use libx264 instead (slower but software-based):
```bash
# Edit master_crisperwhisper.php and change:
$RF_VCODEC = "libx264";
```

### GPU memory issues

Reduce chunk size or video quality:
```bash
$SPLIT_MAXLEN = 120;  # Reduce from 180 to 120 seconds
$RF_CQ = 20;           # Lower quality (higher number = lower quality)
```

## ⚡ Performance

- Processing time depends on video duration and hardware
- GPU acceleration can reduce encoding time by 10-20x
- CPU-only processing takes significantly longer

## 📁 File Structure

```
AutoVoiceCut/
├── master_crisperwhisper.php      # Main orchestration script
├── 1_split_on_silence.php         # Audio splitting script
├── 2_transcribe_chunks_and_merge.php  # Transcription script
├── 3_remove_filler_singlepass_v3.php  # Filler removal script
├── README.md                       # This file
└── chunks/                         # Output directory (created at runtime)
    ├── chunk_000.wav
    ├── chunk_001.wav
    ├── ...
    ├── offsets.json
    ├── full_transcript_text.txt
    ├── full_transcript_timestamps.txt
    └── full_transcript.srt
```

<!-- ## License -->

<!-- ## Contributing -->

<!-- ## Support -->

<!-- ## Authors & Credits -->

<!-- ## Changelog -->

<!-- ## Related Projects -->

<!-- ## Known Issues -->

<!-- ## Development -->

<!-- ### Testing -->

<!-- ### Building -->

<!-- ## Documentation -->

<!-- ## License Information -->

## ⚠️ Disclaimer

This tool is for personal use. Ensure you have the right to process and modify any video content before using this tool.
