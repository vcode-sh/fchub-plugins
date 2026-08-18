import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";

/**
 * Regenerates the fixture from the plugin's own renderers before every run, so
 * the lane can never drift into testing markup that production stopped emitting.
 */
export default function globalSetup() {
	const script = fileURLToPath(new URL("./generate-fixture.php", import.meta.url));
	const output = execFileSync("php", [script], { encoding: "utf8" });
	process.stdout.write(output);
}
