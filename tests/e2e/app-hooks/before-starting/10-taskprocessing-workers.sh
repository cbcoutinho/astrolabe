#!/bin/bash
# Run dedicated TaskProcessing workers.
#
# Nextcloud executes synchronous TaskProcessing providers from a background job,
# which cron drives every 5 minutes. That is far too slow for anything
# interactive: a Summarize click or an Assistant chat turn would sit idle for
# minutes. A dedicated worker polls the queue instead, which takes pickup
# latency down to roughly a second.
#
# Several workers run concurrently so one slow multimodal summary cannot block a
# chat turn queued behind it.
#
# Each worker exits and is restarted periodically because it caches app config
# in memory for its lifetime: a provider API key configured *after* the worker
# started is otherwise never picked up, and every task keeps failing with an
# authentication error against a key that is actually correct. The command's own
# --help calls this out ("You should regularly restart this worker ... to make
# sure it picks up configuration changes").
#
# This runs from before-starting rather than post-installation so the workers
# come back on every container start, not just first install.
set -euo pipefail

WORKERS="${TASKPROCESSING_WORKERS:-4}"
RECYCLE_SECONDS="${TASKPROCESSING_WORKER_TIMEOUT:-300}"

echo "Starting ${WORKERS} TaskProcessing worker(s), recycling every ${RECYCLE_SECONDS}s..."

# The entrypoint's run_as() already invokes this hook as www-data, so no su here:
# calling it would prompt for a password and fail, and because these are
# backgrounded that failure is easy to miss — the hook still reports success
# while no worker is actually running.
#
# setsid detaches each loop into its own session so it survives the entrypoint
# moving on to exec apache.
for _ in $(seq 1 "${WORKERS}"); do
    setsid sh -c "
        while true; do
            php /var/www/html/occ taskprocessing:worker --interval=1 --timeout=${RECYCLE_SECONDS} || true
            sleep 1
        done
    " >/dev/null 2>&1 &
done

# Confirm rather than assume: a worker that failed to start is otherwise
# indistinguishable from a slow provider when tasks sit in the queue.
sleep 2
running="$(pgrep -fc 'taskprocessing:worker' || true)"
echo "TaskProcessing workers started (${running} process(es) live)."
