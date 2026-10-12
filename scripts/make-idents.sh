#!/usr/bin/env bash
#
# The station sound of TWENTY ONE TV, synthesized from nothing but ffmpeg's
# own signal generators (aevalsrc, deterministic pseudo-random noise): no
# sample, no loop, no third-party recording goes in, so the output is our own
# work, released as CC0 (resources/audio/idents/LICENSES.md).
#
#   scripts/make-idents.sh [OUT_DIR]      (default: resources/audio/idents)
#
# Writes three stingers and one loopable news bed as Ogg Opus, 48 kHz MONO:
# the stream's voice input is mono PCM (App\Support\Caster\VoiceFeed) and the
# voice clips are mono -16 LUFS, so a mono file measures exactly what goes on
# air next to the voice (a stereo file at -16 LUFS lands ~3.4 dB lower once
# downmixed, measured 2026-10-12: -16.0 stereo -> -19.4 mono).
#   news-stinger.opus    ~3.6 s  clock ticks, rising sweep, hit, the motif in octaves
#   station-ident.opus   ~2.6 s  short riser, the motif, a soft hit
#   breaking-sting.opus  ~2.0 s  two falling zaps, the motif staccato, a hit
#   news-bed.opus        96 s    quiet pulse and pad, loops seamlessly (every
#                                periodic part divides 96 s, no fades)
# The station signature is a three-note motif, D5 - A5 - F#5, in every file.
#
# Loudness: each file is measured with ebur128 and gained to its target
# (stingers -16 LUFS integrated, the bed -28 LUFS), sample peaks limited at
# -1 dBFS like the stream's own limiter (tv.commentary.mix.limit). The script
# prints the measured values of the encoded files at the end.
#
# Runs under nice with one thread (no CPU stress on the workstation).
set -euo pipefail

OUT_DIR="${1:-resources/audio/idents}"
RATE=48000
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$OUT_DIR"

FF=(nice -n 19 ffmpeg -nostdin -hide_banner -loglevel error -threads 1)

# arithmetic without bc (not installed everywhere)
calc() { awk "BEGIN { printf \"%.4f\", $1 }"; }

# --- expression helpers (aevalsrc syntax; t = seconds) ----------------------

# bell T0 FREQ DECAY AMP: an FM tone (ratio 2, decaying index), 5 ms attack.
bell() {
  local x="(t-$1)"
  echo "$4*gte(t,$1)*min(1,$x*200)*exp(-$x*$3)*sin(2*PI*$2*$x+1.4*exp(-$x*7)*sin(2*PI*$2*2*$x))"
}

# boom T0 AMP: a sub hit falling from ~105 Hz to 45 Hz.
boom() {
  local x="(t-$1)"
  echo "$2*gte(t,$1)*min(1,$x*300)*exp(-$x*5)*sin(2*PI*(45*$x+4*(1-exp(-$x*15))))"
}

# chirp T0 DURATION F0 F1 AMP: an exponential sine sweep, swelling in.
chirp() {
  local x="(t-$1)"
  echo "$5*between(t,$1,$1+$2)*pow($x/$2,2)*sin(2*PI*$3*$2/log($4/$3)*(exp($x/$2*log($4/$3))-1))"
}

# motif T0 STEP DECAY AMP DETUNE: the station signature D5 A5 F#5.
motif() {
  local d="$5"
  echo "$(bell "$1" "587.33*$d" "$3" "$4")+$(bell "$1+$2" "880*$d" "$3" "$4")+$(bell "$1+2*$2" "739.99*$d" "$(calc "$3*0.6")" "$4")"
}

# render NAME DURATION TONAL_L TONAL_R NOISE NOISE_FILTER
#   tonal parts in two channels (the second slightly detuned, folded to mono
#   at the end: a gentle chorus), noise parts in a second source with their
#   own filter, then a short room (aecho), a fade at the end, the mono fold.
render() {
  local name="$1" dur="$2" left="$3" right="$4" noise="$5" nfilter="$6"
  cat >"$WORK/$name.graph" <<GRAPH
aevalsrc=exprs='$left|$right':s=$RATE:d=$dur[tonal];
aevalsrc=exprs='$noise':s=$RATE:d=$dur,$nfilter,pan=stereo|c0=c0|c1=c0[noise];
[tonal][noise]amix=inputs=2:normalize=0,aecho=0.8:0.6:43|71:0.2|0.13,afade=t=out:st=$(calc "$dur-0.5"):d=0.5,pan=mono|c0=0.5*c0+0.5*c1[out]
GRAPH
  "${FF[@]}" -/filter_complex "$WORK/$name.graph" -map '[out]' -c:a pcm_f32le -y "$WORK/$name.raw.wav"
}

# master NAME TARGET_LUFS: gain to the target, limit at -1 dBFS, encode Opus.
master() {
  local name="$1" target="$2" measured gain bitrate=64k
  [ "$name" = news-bed ] && bitrate=48k
  measured="$(nice -n 19 ffmpeg -nostdin -hide_banner -threads 1 -i "$WORK/$name.raw.wav" -af ebur128 -f null - 2>&1 \
    | awk '/Integrated loudness:/{f=1} f&&/I:/{print $2; exit}')"
  gain="$(calc "$target - ($measured)")"
  "${FF[@]}" -i "$WORK/$name.raw.wav" -af "volume=${gain}dB,alimiter=limit=0.891:level=disabled:attack=1:release=50" \
    -c:a pcm_f32le -y "$WORK/$name.mastered.wav"
  # The limiter costs loudness on a hit: one correction pass towards the target.
  measured="$(nice -n 19 ffmpeg -nostdin -hide_banner -threads 1 -i "$WORK/$name.mastered.wav" -af ebur128 -f null - 2>&1 \
    | awk '/Integrated loudness:/{f=1} f&&/I:/{print $2; exit}')"
  gain="$(calc "$target - ($measured)")"
  "${FF[@]}" -i "$WORK/$name.mastered.wav" -af "volume=${gain}dB,alimiter=limit=0.891:level=disabled:attack=1:release=50" \
    -ar "$RATE" -ac 1 -c:a libopus -b:a "$bitrate" -application audio -map_metadata -1 -fflags +bitexact \
    -metadata title="TWENTY ONE TV $name" -metadata copyright="CC0 1.0, own work" -y "$OUT_DIR/$name.opus"
}

# --- the station ident: riser 0-0.5 s, motif from 0.5 s ---------------------
render station-ident 2.6 \
  "$(motif 0.5 0.18 3.2 0.30 1)+$(boom 0.5 0.45)" \
  "$(motif 0.5 0.18 3.2 0.30 1.0012)+$(boom 0.5 0.45)" \
  "0.35*lt(t,0.5)*pow(t/0.5,3)*(2*random(0)-1)+0.30*gte(t,0.5)*exp(-(t-0.5)*30)*(2*random(0)-1)" \
  "highpass=f=1800,lowpass=f=12000"

# --- the news stinger: ticks and sweep 0-1.6 s, hit and motif in octaves ----
NEWS_TONAL="$(chirp 0.9 0.7 220 1800 0.22)+$(boom 1.6 0.6)+$(motif 1.6 0.22 2.2 0.26 1)+$(motif 1.6 0.22 2.6 0.12 2)"
NEWS_TONAL_R="$(chirp 0.9 0.7 220 1800 0.22)+$(boom 1.6 0.6)+$(motif 1.6 0.22 2.2 0.26 1.0012)+$(motif 1.6 0.22 2.6 0.12 2.0024)"
render news-stinger 3.6 "$NEWS_TONAL" "$NEWS_TONAL_R" \
  "lt(t,1.6)*(0.18+0.25*t/1.6)*exp(-mod(t,0.125)*90)*(2*random(0)-1)+0.30*between(t,0.6,1.6)*pow((t-0.6),3)*(2*random(0)-1)+0.4*gte(t,1.6)*exp(-(t-1.6)*22)*(2*random(0)-1)" \
  "highpass=f=2500,lowpass=f=14000"

# --- the breaking sting: two falling zaps, the motif staccato, a hit --------
render breaking-sting 2.0 \
  "$(chirp 0 0.22 2400 180 0.28)+$(chirp 0.3 0.22 2400 180 0.28)+$(boom 0.62 0.55)+$(motif 0.62 0.1 7 0.32 1)" \
  "$(chirp 0 0.22 2400 180 0.28)+$(chirp 0.3 0.22 2400 180 0.28)+$(boom 0.62 0.55)+$(motif 0.62 0.1 7 0.32 1.0012)" \
  "0.35*gte(t,0.62)*exp(-(t-0.62)*18)*(2*random(0)-1)+0.12*lt(t,0.55)*exp(-mod(t,0.3)*25)*(2*random(0)-1)" \
  "highpass=f=1500,lowpass=f=13000"

# --- the news bed: 96 s, every period divides 96 s --------------------------
# Frequencies are multiples of 1/96 Hz, so every sine is back in phase at 96 s.
# Drone D2 + A2 + D3; a pad walking D - Bm - G - A (F#3, B3, G3, A3 with their
# fifths) under smooth periodic cross-fades; a soft sub pulse on every beat
# (120 bpm) and quiet ticks on the eighths; the motif every 8 s, very low.
q() { awk -v f="$1" 'BEGIN { printf "%.6f", int(f * 96 + 0.5) / 96 }'; }
w() { echo "pow(0.5+0.5*cos(2*PI*(t/96-$1)),4)"; }
DRONE="0.10*sin(2*PI*$(q 73.42)*t)+0.07*sin(2*PI*110*t)+0.05*sin(2*PI*$(q 146.83)*t)"
PAD="$(w 0)*(0.06*sin(2*PI*$(q 185)*t)+0.03*sin(2*PI*$(q 277.18)*t))+$(w 0.25)*(0.06*sin(2*PI*$(q 246.94)*t)+0.03*sin(2*PI*$(q 369.99)*t))+$(w 0.5)*(0.06*sin(2*PI*196*t)+0.03*sin(2*PI*$(q 293.66)*t))+$(w 0.75)*(0.06*sin(2*PI*220*t)+0.03*sin(2*PI*$(q 329.63)*t))"
PULSE="0.16*exp(-mod(t,0.5)*9)*sin(2*PI*$(q 73.42)*mod(t,0.5))"
BEDMOTIF="0.05*exp(-mod(t,8)*2.5)*sin(2*PI*587.33*mod(t,8))+0.05*gte(mod(t,8),0.25)*exp(-(mod(t,8)-0.25)*2.5)*sin(2*PI*880*(mod(t,8)-0.25))+0.05*gte(mod(t,8),0.5)*exp(-(mod(t,8)-0.5)*1.8)*sin(2*PI*739.99*(mod(t,8)-0.5))"
cat >"$WORK/news-bed.graph" <<GRAPH
aevalsrc=exprs='$DRONE+$PAD+$PULSE+$BEDMOTIF|$DRONE+$PAD+$PULSE+$BEDMOTIF':s=$RATE:d=96[tonal];
aevalsrc=exprs='(0.10+0.06*eq(mod(floor(t/0.25),2),0))*exp(-mod(t,0.25)*60)*(2*random(0)-1)':s=$RATE:d=96,highpass=f=6000,pan=stereo|c0=c0|c1=c0[noise];
[tonal][noise]amix=inputs=2:normalize=0,pan=mono|c0=0.5*c0+0.5*c1[out]
GRAPH
"${FF[@]}" -/filter_complex "$WORK/news-bed.graph" -map '[out]' -c:a pcm_f32le -y "$WORK/news-bed.raw.wav"

for name in news-stinger station-ident breaking-sting; do master "$name" -16; done
master news-bed -28

echo "file                 LUFS(I)  peak(dBFS)  seconds"
for f in "$OUT_DIR"/*.opus; do
  stats="$(nice -n 19 ffmpeg -nostdin -hide_banner -threads 1 -i "$f" -af ebur128=peak=sample -f null - 2>&1)"
  i="$(echo "$stats" | awk '/Integrated loudness:/{f=1} f&&/I:/{print $2; exit}')"
  p="$(echo "$stats" | awk '/Sample peak:/{f=1} f&&/Peak:/{print $2; exit}')"
  s="$(ffprobe -v error -show_entries format=duration -of csv=p=0 "$f")"
  printf '%-20s %7s  %10s  %7.2f\n' "$(basename "$f")" "$i" "$p" "$s"
done
