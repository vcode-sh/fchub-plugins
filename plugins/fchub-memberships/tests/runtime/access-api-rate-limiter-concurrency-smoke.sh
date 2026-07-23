#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
PLAYGROUND_DIR="$(cd "${PLUGIN_DIR}/../../../fchub-playground" && pwd)"
CONTAINER_PLUGIN_DIR="/var/www/html/wp-content/plugins/fchub-memberships"
TOKEN="$(php -r 'echo bin2hex(random_bytes(16));')"
CREDENTIAL_PREFIX="runtime-${TOKEN}"
TEMP_DIR="$(mktemp -d "${SCRIPT_DIR}/.access-api-rate-limit-${TOKEN}-XXXXXX")"
CONTAINER_TEMP_DIR="${CONTAINER_PLUGIN_DIR}/tests/runtime/$(basename "${TEMP_DIR}")"
START_FILE="${TEMP_DIR}/start"
READY_ONE="${TEMP_DIR}/ready-1"
READY_TWO="${TEMP_DIR}/ready-2"
RESULT_ONE="${TEMP_DIR}/result-1.json"
RESULT_TWO="${TEMP_DIR}/result-2.json"
ERROR_ONE="${TEMP_DIR}/error-1.log"
ERROR_TWO="${TEMP_DIR}/error-2.log"
WORKER_ONE_PID=""
WORKER_TWO_PID=""

chmod 0777 "${TEMP_DIR}"

cleanup_state() {
    (
        cd "${PLAYGROUND_DIR}"
        docker compose exec -T \
            -e FCHUB_RATE_PREFIX="${CREDENTIAL_PREFIX}" \
            wpcli wp eval '
                $prefix = (string) getenv("FCHUB_RATE_PREFIX");
                $key = "fchub_memberships_access_api_" . hash("sha256", $prefix);
                delete_transient($key);
            ' >/dev/null 2>&1 || true
    )
}

cleanup() {
    if [[ -n "${WORKER_ONE_PID}" ]]; then
        kill "${WORKER_ONE_PID}" >/dev/null 2>&1 || true
    fi
    if [[ -n "${WORKER_TWO_PID}" ]]; then
        kill "${WORKER_TWO_PID}" >/dev/null 2>&1 || true
    fi
    cleanup_state
    rm -rf "${TEMP_DIR}"
}
trap cleanup EXIT

run_worker() {
    local worker_id="$1"
    local result_file="$2"
    local error_file="$3"

    (
        cd "${PLAYGROUND_DIR}"
        docker compose exec -T \
            -e FCHUB_RATE_PREFIX="${CREDENTIAL_PREFIX}" \
            -e FCHUB_RATE_TEMP_DIR="${CONTAINER_TEMP_DIR}" \
            -e FCHUB_RATE_WORKER_ID="${worker_id}" \
            wpcli wp eval '
                $prefix = (string) getenv("FCHUB_RATE_PREFIX");
                $tempDir = (string) getenv("FCHUB_RATE_TEMP_DIR");
                $workerId = (string) getenv("FCHUB_RATE_WORKER_ID");
                $key = "fchub_memberships_access_api_" . hash("sha256", $prefix);

                file_put_contents($tempDir . "/ready-" . $workerId, "ready");
                $deadline = microtime(true) + 10;
                while (!is_file($tempDir . "/start")) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException("The concurrency barrier timed out.");
                    }
                    usleep(10000);
                }

                add_filter(
                    "fchub_memberships/access_api_rate_limit",
                    static fn(): int => 1
                );
                add_filter(
                    "pre_set_transient_{$key}",
                    static function (mixed $value): mixed {
                        usleep(750000);
                        return $value;
                    }
                );

                echo wp_json_encode(
                    (new FChubMemberships\Http\AccessApiRateLimiter())->consume($prefix)
                );
            '
    ) >"${result_file}" 2>"${error_file}"
}

(
    cd "${PLAYGROUND_DIR}"
    docker compose exec -T \
        -e FCHUB_RATE_PREFIX="${CREDENTIAL_PREFIX}" \
        wpcli wp eval '
            global $wpdb;

            $prefix = (string) getenv("FCHUB_RATE_PREFIX");
            $key = "fchub_memberships_access_api_" . hash("sha256", $prefix);
            $scope = (string) $wpdb->dbname
                . "\0"
                . (string) $wpdb->prefix
                . "\0"
                . (int) get_current_blog_id()
                . "\0"
                . $prefix;
            $lock = "fchub_access_" . substr(hash("sha256", $scope), 0, 51);
            $rows = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options}
                 WHERE option_name IN (%s, %s)",
                "_transient_{$key}",
                "_transient_timeout_{$key}"
            ));

            if (get_transient($key) !== false || $rows !== 0) {
                throw new RuntimeException("The disposable transient already exists.");
            }
            if ((int) $wpdb->get_var($wpdb->prepare("SELECT IS_FREE_LOCK(%s)", $lock)) !== 1) {
                throw new RuntimeException("The disposable advisory lock is not free.");
            }
        '
)

run_worker 1 "${RESULT_ONE}" "${ERROR_ONE}" &
WORKER_ONE_PID="$!"
run_worker 2 "${RESULT_TWO}" "${ERROR_TWO}" &
WORKER_TWO_PID="$!"

for _ in {1..200}; do
    if [[ -f "${READY_ONE}" && -f "${READY_TWO}" ]]; then
        break
    fi
    sleep 0.05
done

if [[ ! -f "${READY_ONE}" || ! -f "${READY_TWO}" ]]; then
    echo "Both rate-limit workers did not reach the concurrency barrier." >&2
    exit 1
fi

touch "${START_FILE}"
wait "${WORKER_ONE_PID}"
WORKER_ONE_PID=""
wait "${WORKER_TWO_PID}"
WORKER_TWO_PID=""

# The single quotes deliberately preserve PHP variables for php -r.
# shellcheck disable=SC2016
php -r '
    $results = [
        json_decode((string) file_get_contents($argv[1]), true),
        json_decode((string) file_get_contents($argv[2]), true),
    ];
    if (!is_array($results[0]) || !is_array($results[1])) {
        fwrite(STDERR, "A rate-limit worker returned invalid JSON.\n");
        exit(1);
    }
    $allowed = array_values(array_filter($results, static fn(array $result): bool => $result["allowed"] === true));
    $denied = array_values(array_filter($results, static fn(array $result): bool => $result["allowed"] === false));
    if (count($allowed) !== 1 || count($denied) !== 1) {
        fwrite(STDERR, "Concurrent workers did not produce exactly one admission and one rejection.\n");
        exit(1);
    }
    foreach ($results as $result) {
        if ($result["limit"] !== 1 || $result["remaining"] !== 0) {
            fwrite(STDERR, "A worker returned the wrong filtered limit state.\n");
            exit(1);
        }
    }
    if ($allowed[0]["retry_after"] !== 0
        || $denied[0]["retry_after"] < 59
        || $denied[0]["retry_after"] > 60
    ) {
        fwrite(STDERR, "A worker returned the wrong fixed-window retry state.\n");
        exit(1);
    }
' "${RESULT_ONE}" "${RESULT_TWO}"

(
    cd "${PLAYGROUND_DIR}"
    docker compose exec -T \
        -e FCHUB_RATE_PREFIX="${CREDENTIAL_PREFIX}" \
        wpcli wp eval '
            global $wpdb;

            $prefix = (string) getenv("FCHUB_RATE_PREFIX");
            $key = "fchub_memberships_access_api_" . hash("sha256", $prefix);
            $scope = (string) $wpdb->dbname
                . "\0"
                . (string) $wpdb->prefix
                . "\0"
                . (int) get_current_blog_id()
                . "\0"
                . $prefix;
            $lock = "fchub_access_" . substr(hash("sha256", $scope), 0, 51);
            $state = get_transient($key);

            if (!is_array($state) || (int) ($state["count"] ?? 0) !== 1) {
                throw new RuntimeException("The concurrent rate-limit count is not exactly one.");
            }
            if ((int) $wpdb->get_var($wpdb->prepare("SELECT IS_FREE_LOCK(%s)", $lock)) !== 1) {
                throw new RuntimeException("The advisory lock was not released.");
            }

            delete_transient($key);
            $rows = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options}
                 WHERE option_name IN (%s, %s)",
                "_transient_{$key}",
                "_transient_timeout_{$key}"
            ));
            if (get_transient($key) !== false || $rows !== 0) {
                throw new RuntimeException("The disposable transient was not removed.");
            }
            if ((int) $wpdb->get_var($wpdb->prepare("SELECT IS_FREE_LOCK(%s)", $lock)) !== 1) {
                throw new RuntimeException("The advisory lock was not clean after transient cleanup.");
            }
        '
)

echo "Access API rate-limit concurrency smoke passed; one request was admitted, one was rejected, and transient/lock state was removed."
