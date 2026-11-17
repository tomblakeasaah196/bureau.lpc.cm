"""The narration of a Bureau LPC training video (docs/TRAINING_VIDEOS.md §2, §3).

    python scripts/video/narrate.py speak <lines.json> <out-dir>
        lines.json: {"engine": "edge", "voice": "fr-FR-DeniseNeural", "rate": "-4%",
                     "lines": [{"id": "c01s01l01", "speech": "Bienvenue sur L P C E R P."}]}
        Writes <out-dir>/<id>.wav for each line not already there with the same words and voice
        (24 kHz mono, silence trimmed) and <out-dir>/durations.json, {"<id>": seconds}.

    python scripts/video/narrate.py mix <timeline.json> <out.wav>
        timeline.json: {"length": seconds, "clips": [{"file": "...wav", "at": seconds}]}
        Lays every clip at its time on one silent track.

Two engines, chosen by the owner on 17/11/2026 (voice audition, nine voices):
  edge    Microsoft Denise (fr-FR-DeniseNeural), the owner's choice, through Microsoft Edge's
          read-aloud service: free, no account, but unofficial (an internal training video, the
          owner's decision). The script's words are sent to Microsoft. The service drops a
          connection now and then, so each line is retried; a line that still fails stops the run
          rather than mixing two voices in one video.
  kokoro  Kokoro's ff_siwis, the fallback: runs on this machine, nothing leaves it.
Loudness is set later, by ffmpeg, on the finished track.
"""
import asyncio
import json
import os
import subprocess
import sys
import tempfile
import time

import numpy as np
import soundfile as sf

RATE = 24000


def trim(audio, floor_db=-40.0, pad=0.03):
    """Cut the silence the engine leaves at each end, keeping `pad` seconds either side."""
    if audio.size == 0:
        return audio
    frame = 240
    n = len(audio) // frame
    if n == 0:
        return audio
    rms = np.sqrt((audio[: n * frame].reshape(n, frame) ** 2).mean(axis=1) + 1e-12)
    loud = np.where(20 * np.log10(rms / (rms.max() + 1e-12)) > floor_db)[0]
    if loud.size == 0:
        return audio
    p = int(pad * RATE)
    return audio[max(0, loud[0] * frame - p): min(len(audio), (loud[-1] + 1) * frame + p)]


def decode(path):
    """Any audio file as 24 kHz mono float32, through ffmpeg."""
    raw = subprocess.run(
        [os.environ.get("FFMPEG_PATH", "ffmpeg"), "-v", "error", "-i", path, "-f", "f32le", "-ac", "1", "-ar", str(RATE), "-"],
        check=True, capture_output=True).stdout
    return np.frombuffer(raw, dtype=np.float32).copy()


class Edge:
    def __init__(self, spec):
        import edge_tts  # noqa: F401  (fail early if the venv lacks it)
        self.voice = spec.get("voice", "fr-FR-DeniseNeural")
        self.rate = spec.get("rate", "-4%")
        self.key = f"edge|{self.voice}|{self.rate}"

    def say(self, text):
        import edge_tts
        last = None
        for attempt in range(6):
            fd, tmp = tempfile.mkstemp(suffix=".mp3")
            os.close(fd)
            try:
                asyncio.run(asyncio.wait_for(edge_tts.Communicate(text, self.voice, rate=self.rate).save(tmp), 60))
                audio = decode(tmp)
                # A dropped connection can leave a clip cut short: about 0.05 s a character is the floor.
                if len(audio) / RATE >= max(0.6, len(text) * 0.035):
                    return audio
                last = RuntimeError(f"short clip ({len(audio) / RATE:.2f}s for {len(text)} characters)")
            except Exception as e:  # noqa: BLE001  (the service fails in many ways; all are retried)
                last = e
            finally:
                try:
                    os.remove(tmp)
                except OSError:
                    pass
            time.sleep(2 + attempt * 2)
        raise RuntimeError(f"Microsoft's read-aloud service failed six times for: {text!r} ({last})")


class Kokoro:
    def __init__(self, spec):
        from kokoro import KPipeline
        self.voice = spec.get("voice", "ff_siwis")
        self.speed = spec.get("speed", 0.95)
        self.key = f"kokoro|{self.voice}|{self.speed}"
        self.pipe = KPipeline(lang_code="f", repo_id="hexgrad/Kokoro-82M")

    def say(self, text):
        chunks = [a.numpy() if hasattr(a, "numpy") else np.asarray(a) for _, _, a in self.pipe(text, voice=self.voice, speed=self.speed)]
        return np.concatenate(chunks).astype(np.float32)


ENGINES = {"edge": Edge, "kokoro": Kokoro}


def speak(lines_path, out_dir):
    spec = json.load(open(lines_path, encoding="utf-8"))
    os.makedirs(out_dir, exist_ok=True)
    durations_path = os.path.join(out_dir, "durations.json")
    durations = json.load(open(durations_path)) if os.path.exists(durations_path) else {}
    engine = None

    for line in spec["lines"]:
        wav = os.path.join(out_dir, line["id"] + ".wav")
        stamp = os.path.join(out_dir, line["id"] + ".txt")
        if engine is None:
            engine = ENGINES[spec.get("engine", "edge")](spec)
        said = f'{engine.key}|{line["speech"]}'
        if os.path.exists(wav) and os.path.exists(stamp) and open(stamp, encoding="utf-8").read() == said:
            continue
        audio = trim(engine.say(line["speech"]))
        sf.write(wav, audio, RATE)
        open(stamp, "w", encoding="utf-8").write(said)
        durations[line["id"]] = round(len(audio) / RATE, 3)
        json.dump(durations, open(durations_path, "w"), indent=1)  # kept as it goes: a failed run resumes
        print(f'{line["id"]} {durations[line["id"]]:.2f}s', flush=True)
    for line in spec["lines"]:
        if line["id"] not in durations:
            durations[line["id"]] = round(sf.info(os.path.join(out_dir, line["id"] + ".wav")).duration, 3)
    json.dump(durations, open(durations_path, "w"), indent=1)


def mix(timeline_path, out_path):
    t = json.load(open(timeline_path, encoding="utf-8"))
    track = np.zeros(int(t["length"] * RATE) + RATE, dtype=np.float32)
    for clip in t["clips"]:
        audio, rate = sf.read(clip["file"], dtype="float32")
        assert rate == RATE, f'{clip["file"]} is {rate} Hz'
        start = int(clip["at"] * RATE)
        end = min(len(track), start + len(audio))
        track[start:end] += audio[: end - start]
    sf.write(out_path, track[: int(t["length"] * RATE)], RATE)


if __name__ == "__main__":
    if len(sys.argv) != 4 or sys.argv[1] not in ("speak", "mix"):
        sys.exit(__doc__)
    (speak if sys.argv[1] == "speak" else mix)(sys.argv[2], sys.argv[3])
