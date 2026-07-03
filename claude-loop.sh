#!/usr/bin/env bash
#
# claude-loop.sh — run Claude Code headlessly in a loop, clearing context every N iterations.
#
# How it works:
#   • Iterations 1-5 share ONE session (context persists, so Claude remembers its own progress).
#   • Iteration 6 starts a FRESH session (context cleared). Then 6-10 share it, 11 resets, etc.
#   • After each batch of N, a QUALITY pass runs the code-review + simplify skills on
#     the PHP files committed during that batch (disable with --no-maintain).
#   • State that must survive a reset lives in FILES (loop-prompt.md → browser-testing.md + git), not chat.
#
# Usage:
#   ./claude-loop.sh                 # defaults below (50 iters, opus, max effort, auto perms)
#   ./claude-loop.sh 20              # 20 iterations
#   ./claude-loop.sh -n 100 -r 5     # 100 iterations, reset context every 5
#   ./claude-loop.sh --no-maintain   # skip the per-batch quality pass
#   ./claude-loop.sh --ntfy my-loop  # also push progress to https://ntfy.sh/my-loop (phone)
#   ./claude-loop.sh --help
#
set -uo pipefail   # NOT -e: a failed iteration should not abort the whole loop

# ── Config (override via env, then CLI flags below) ─────────────────────────
PROMPT_FILE="${PROMPT_FILE:-loop-prompt.md}"          # the task. Full instructions go here.
TASKS_FILE="${TASKS_FILE:-browser-testing.md}"        # the task board (for the Progress readout + continue nudge)
RESET_EVERY="${RESET_EVERY:-5}"                       # clear context + run quality pass every N iterations
MAX_ITERATIONS="${MAX_ITERATIONS:-50}"                # hard stop after N total (0 = unlimited)
DONE_MARKER="${DONE_MARKER:-LOOP-DONE}"               # stop when Claude prints this (matches the task board)
MODEL="${MODEL:-opus}"                                # e.g. "opus" / "sonnet"; empty = account default
EFFORT="${EFFORT:-max}"                               # reasoning effort: low | medium | high | xhigh | max
PERM_MODE="${PERM_MODE:-auto}"                        # auto | acceptEdits | bypassPermissions | default
LOG_DIR="${LOG_DIR:-.loop-logs}"
NTFY_TOPIC="${NTFY_TOPIC:-}"                           # if set, push progress to https://ntfy.sh/<topic>
MAINTAIN="${MAINTAIN:-1}"                              # 1 = quality pass after each batch; 0 = off (--no-maintain)
MAINTAIN_PROMPT_FILE="${MAINTAIN_PROMPT_FILE:-maintain-prompt.md}"   # quality-pass instructions
CONTINUE_NUDGE="${CONTINUE_NUDGE:-Continue: re-read ${TASKS_FILE} and do the next eligible task following the same one-task-per-loop procedure. If (and only if) no eligible task remains, output ${DONE_MARKER} on its own line as your entire reply; otherwise do NOT write that token anywhere.}"
# ─────────────────────────────────────────────────────────────────────────────

usage() {
  cat <<EOF
Usage: ./claude-loop.sh [N] [options]
  N                          shorthand for --max-iterations N
  -n, --max-iterations N     hard stop after N iterations (default ${MAX_ITERATIONS}; 0 = unlimited)
  -r, --reset-every N        clear context + quality pass every N iterations (default ${RESET_EVERY})
  -m, --model NAME           model alias, e.g. opus|sonnet (default ${MODEL})
  -e, --effort LEVEL         low|medium|high|xhigh|max (default ${EFFORT})
      --perm MODE            auto|acceptEdits|bypassPermissions|default (default ${PERM_MODE})
  -f, --prompt FILE          task prompt file (default ${PROMPT_FILE})
      --tasks FILE           task board for the Progress readout + continue nudge (default ${TASKS_FILE})
      --no-maintain          skip the code-review + simplify quality pass after each batch
      --maintain-prompt FILE quality-pass prompt file (default ${MAINTAIN_PROMPT_FILE})
      --ntfy TOPIC           push progress to https://ntfy.sh/TOPIC (check from your phone)
  -h, --help                 show this help
Env vars also work (MAX_ITERATIONS, RESET_EVERY, MODEL, EFFORT, PERM_MODE, TASKS_FILE, NTFY_TOPIC, MAINTAIN, …); CLI wins.
EOF
}

# ── CLI args override env/defaults ──────────────────────────────────────────
while [[ $# -gt 0 ]]; do
  case "$1" in
    -n|--max-iterations) MAX_ITERATIONS="${2:?--max-iterations needs a number}"; shift 2 ;;
    -r|--reset-every)    RESET_EVERY="${2:?--reset-every needs a number}"; shift 2 ;;
    -m|--model)          MODEL="${2:?--model needs a value}"; shift 2 ;;
    -e|--effort)         EFFORT="${2:?--effort needs a level}"; shift 2 ;;
    --perm)              PERM_MODE="${2:?--perm needs a mode}"; shift 2 ;;
    -f|--prompt)         PROMPT_FILE="${2:?--prompt needs a file}"; shift 2 ;;
    --tasks)             TASKS_FILE="${2:?--tasks needs a file}"; shift 2 ;;
    --no-maintain)       MAINTAIN=0; shift ;;
    --maintain-prompt)   MAINTAIN_PROMPT_FILE="${2:?--maintain-prompt needs a file}"; shift 2 ;;
    --ntfy)              NTFY_TOPIC="${2:?--ntfy needs a topic}"; shift 2 ;;
    -h|--help)           usage; exit 0 ;;
    *[!0-9]*|'')         echo "✗ Unknown argument: $1" >&2; usage; exit 1 ;;
    *)                   MAX_ITERATIONS="$1"; shift ;;   # a bare integer = max-iterations
  esac
done

# Validate numeric options before any arithmetic: explicit --max-iterations/--reset-every bypass the
# bare-arg digit check above, so a value like 0 or "abc" would otherwise reach the `% RESET_EVERY`
# modulo and abort mid-run (division by zero / unbound variable).
if [[ ! "$MAX_ITERATIONS" =~ ^[0-9]+$ ]]; then
  echo "✗ --max-iterations must be a non-negative integer (0 = unlimited); got '$MAX_ITERATIONS'." >&2; exit 1
fi
if [[ ! "$RESET_EVERY" =~ ^[0-9]+$ ]] || (( RESET_EVERY < 1 )); then
  echo "✗ --reset-every must be a positive integer (≥ 1); got '$RESET_EVERY'." >&2; exit 1
fi

[[ -f "$PROMPT_FILE" ]] || { echo "✗ Prompt file '$PROMPT_FILE' not found. Create it (see loop-prompt.md)."; exit 1; }
if (( MAINTAIN == 1 )) && [[ ! -f "$MAINTAIN_PROMPT_FILE" ]]; then
  echo "✗ Maintenance prompt '$MAINTAIN_PROMPT_FILE' not found (create it, or pass --no-maintain)."; exit 1
fi
command -v claude >/dev/null || { echo "✗ 'claude' CLI not on PATH."; exit 1; }
mkdir -p "$LOG_DIR"

model_arg=();  [[ -n "$MODEL"  ]] && model_arg=(--model "$MODEL")
effort_arg=(); [[ -n "$EFFORT" ]] && effort_arg=(--effort "$EFFORT")

# Push a one-line message to your phone via ntfy.sh (no-op unless --ntfy/NTFY_TOPIC is set).
notify() {
  [[ -n "$NTFY_TOPIC" ]] || return 0
  curl -fsS -m 10 -H "Title: claude-loop" -d "$1" "https://ntfy.sh/${NTFY_TOPIC}" >/dev/null 2>&1 || true
}

# Quality pass over the PHP files committed since $1 (the sha at the start of the batch):
# runs the code-review skill, then the simplify skill, in a FRESH isolated session.
run_maintenance() {
  local base="$1" label="$2"
  (( MAINTAIN == 1 )) || return 0
  [[ -n "$base" ]] || return 0
  local files
  files="$(git diff --name-only "$base" HEAD 2>/dev/null | grep -E '\.php$' || true)"
  if [[ -z "$files" ]]; then
    echo "  · maintenance (${label}): no PHP files changed since ${base:0:7} — skipping"
    return 0
  fi
  local msid mlog count
  msid="$(uuidgen | tr 'A-F' 'a-f')"
  mlog="${LOG_DIR}/maintain-$(printf '%04d' "$i").log"
  count="$(printf '%s\n' "$files" | wc -l | tr -d ' ')"
  echo "── 🧽 MAINTENANCE (${label}) · ${count} php file(s) changed since ${base:0:7} · session ${msid}"
  notify "🧽 maintenance (${label}): reviewing ${count} file(s)"
  local mprompt
  mprompt="$(cat "$MAINTAIN_PROMPT_FILE")

The previous batch is the commit range ${base}..HEAD — inspect it with:  git diff ${base} HEAD
Review ONLY these changed PHP files:
${files}"
  claude -p --session-id "$msid" "${model_arg[@]}" "${effort_arg[@]}" --permission-mode "$PERM_MODE" "$mprompt" 2>&1 | tee "$mlog"
  local mrc=${PIPESTATUS[0]}
  if (( mrc == 130 || mrc == 143 )); then
    printf '\n■ interrupted during maintenance — stopping.\n'; exit "$mrc"
  fi
  echo "  ↳ maintenance done · HEAD: $(git log -1 --oneline 2>/dev/null)"
  notify "🧽 maintenance (${label}) done · $(git log -1 --oneline 2>/dev/null)"
}

sid=""
batch_start_sha=""
i=0

# One Ctrl-C cleanly stops the whole loop. Without this trap, bash usually kills only the
# current `claude` call and then starts the next iteration — so it feels like it "won't die".
trap 'printf "\n■ Interrupted — stopping claude-loop.\n"; exit 130' INT TERM

start_line="▶ claude-loop: model=${MODEL:-default} effort=${EFFORT:-default} perm=${PERM_MODE} | reset/${RESET_EVERY}, max=${MAX_ITERATIONS:-∞}, maintain=${MAINTAIN}, prompt=${PROMPT_FILE}, board=${TASKS_FILE}${NTFY_TOPIC:+, ntfy=${NTFY_TOPIC}}"
echo "$start_line"
notify "$start_line"

while :; do
  i=$((i + 1))
  if (( MAX_ITERATIONS > 0 && i > MAX_ITERATIONS )); then
    (( (i - 1) % RESET_EVERY != 0 )) && run_maintenance "$batch_start_sha" "final partial batch through iter $((i - 1))"
    echo "■ Reached MAX_ITERATIONS=${MAX_ITERATIONS}. Stopping."
    notify "■ stopped: reached MAX_ITERATIONS=${MAX_ITERATIONS}"
    break
  fi

  pos=$(( (i - 1) % RESET_EVERY ))   # 0 == first turn of a fresh batch
  log="${LOG_DIR}/iter-$(printf '%04d' "$i").log"

  if (( pos == 0 )); then
    sid=$(uuidgen | tr 'A-F' 'a-f')
    batch_start_sha="$(git rev-parse HEAD 2>/dev/null || true)"   # baseline for this batch's quality pass
    echo "── iter ${i} ── 🧹 FRESH context (session ${sid})"
    claude -p --session-id "$sid" "${model_arg[@]}" "${effort_arg[@]}" --permission-mode "$PERM_MODE" "$(cat "$PROMPT_FILE")" 2>&1 | tee "$log"
  else
    echo "── iter ${i} ── ↻ continue (session ${sid}, step $((pos + 1))/${RESET_EVERY} of batch)"
    claude -p --resume "$sid" "${model_arg[@]}" "${effort_arg[@]}" --permission-mode "$PERM_MODE" "$CONTINUE_NUDGE" 2>&1 | tee "$log"
  fi
  rc=${PIPESTATUS[0]}
  if (( rc == 130 || rc == 143 )); then   # 130 = SIGINT (Ctrl-C), 143 = SIGTERM
    printf '\n■ claude interrupted (rc=%s) — stopping loop.\n' "$rc"
    exit "$rc"
  fi

  head_line="$(git log -1 --oneline 2>/dev/null || echo 'no commits')"
  progress="$(grep -m1 -oE 'Progress: ?.[0-9]+ */ *[0-9]+' "$TASKS_FILE" 2>/dev/null || true)"
  if (( rc != 0 )); then
    echo "  ⚠️  iter ${i}: claude exited rc=${rc} (auto-mode may have aborted; see ${log})"
    notify "⚠️ iter ${i} error rc=${rc} — ${progress}"
  else
    echo "  ↳ iter ${i} done · ${progress} · HEAD: ${head_line}"
    notify "✓ iter ${i} · ${progress} · ${head_line}"
  fi

  # "Done" ONLY when the marker is on a line BY ITSELF (optionally wrapped in spaces / markdown
  # punctuation like backticks). This stops the model merely *mentioning* it in prose — e.g.
  # "…eligible work still remains, so no LOOP-DONE" — from falsely ending the loop.
  is_done=0
  if [[ -n "$DONE_MARKER" ]] && grep -qE "^[[:space:]\`*_.~#>-]*${DONE_MARKER}[[:space:]\`*_.~#>-]*$" "$log"; then is_done=1; fi

  # Quality pass after every full batch of RESET_EVERY, else on the final partial batch before stopping.
  if (( i % RESET_EVERY == 0 )); then
    run_maintenance "$batch_start_sha" "iters $((i - RESET_EVERY + 1))-${i}"
  elif (( is_done == 1 )); then
    run_maintenance "$batch_start_sha" "final partial batch through iter ${i}"
  fi

  if (( is_done == 1 )); then
    echo "✅ Done marker (${DONE_MARKER}) detected at iter ${i}. Stopping."
    notify "✅ LOOP-DONE at iter ${i} · ${progress}"
    break
  fi
done
