import { expect, test } from "@playwright/test";
import { readFileSync } from "node:fs";

const adminCss = readFileSync(new URL("../../admin/multi-currency-admin.css", import.meta.url), "utf8");

test("WordPress does not expose its native radio behind the disabled Element Plus control", async ({ page }) => {
	await page.setContent(`
		<style>
			input[type="radio"] {
				display: inline-flex;
				height: 1rem;
				margin: -0.25rem 0.25rem 0 0;
				width: 1rem;
			}
			.el-radio__original {
				inset: 0;
				margin: 0;
				opacity: 0;
				position: absolute;
				z-index: -1;
			}
			input[type="radio"]:disabled {
				opacity: 0.7;
			}
			.el-radio__input {
				position: relative;
			}
			.el-radio__inner {
				display: block;
				height: 16px;
				width: 16px;
			}
			${adminCss}
		</style>
		<div class="fchub-mc-coming-soon-row">
			<label class="el-radio is-disabled is-checked">
				<span class="el-radio__input is-disabled is-checked">
					<input class="el-radio__original" disabled type="radio" checked>
					<span class="el-radio__inner"></span>
				</span>
			</label>
		</div>
	`);

	const nativeRadio = page.locator(".el-radio__original");

	await expect(nativeRadio).toHaveCSS("opacity", "0");
});
